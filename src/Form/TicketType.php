<?php

namespace App\Form;

use App\Entity\Ticket;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class TicketType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'required' => true,
                'constraints' => [
                    new NotBlank(
                        message: 'Veuillez saisir un titre.',
                        normalizer: static fn (?string $value): ?string => null === $value ? null : trim($value),
                    ),
                    new Length(
                        max: 255,
                        maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères.',
                    ),
                ],
            ])
            ->add('description', TextareaType::class, [
                'required' => true,
                'constraints' => [
                    new NotBlank(
                        message: 'Veuillez décrire votre problème.',
                        normalizer: static fn (?string $value): ?string => null === $value ? null : trim($value),
                    ),
                    new Length(
                        max: 50000,
                        maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ticket::class,
        ]);
    }
}
